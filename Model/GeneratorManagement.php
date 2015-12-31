<?php

namespace CoreStore\ConfigGen\Model;

use CoreStore\ConfigGen\Api\GeneratorInterface;

class GeneratorManagement implements GeneratorInterface
{
    const SCOPE = 'adminhtml';

    /**
     * Object manager
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $_objectManager;

    /**
     * GeneratorManagement constructor.
     * @param \Magento\Framework\ObjectManagerInterface $_objectManager
     */
    public function __construct(\Magento\Framework\ObjectManagerInterface $_objectManager)
    {
        $this->_objectManager = $_objectManager;
    }

    // This retrieves a merged config included executed plugins. not what we quite had into our mind.
    //** @var \Magento\Config\Model\Config\Structure\Reader $reader */
    //$reader = $this->_objectManager->create('\Magento\Config\Model\Config\Structure\Reader');
    // $data = $reader->read(self::SCOPE)['config'];

    public function generate()
    {
        $ret = [];

        @mkdir('zcode', 0700);

        foreach (glob("app/code/Magento/*/etc/adminhtml/system.xml") as $systemXML) {
            $module = preg_replace('(app/code/Magento/(.*)/etc/adminhtml/system.xml)', '$1', $systemXML);
            $ret[] = "Writing $module";
            $xml = simplexml_load_file($systemXML);
            $sections = [];
            $pathVariables = [];
            $moduleDefaultConfig = $this->getDefaultConfig(str_replace('adminhtml/system.xml', 'config.xml', $systemXML));
            $moduleDefaultConfigFlat = $this->getDefaultConfigFlat($moduleDefaultConfig);

            foreach ($xml as $key => $value) {
                foreach ($value->section as $section) {
                    $tplSection = $this->section($section);
                    $groups = [];
                    foreach ($section->group as $group) {

                        // @todo recursive groups with config_path in field's
                        // for brain tree i've done merging manually
                        //                if(isset($group->group)){
                        //                    var_dump(count($group->group));
                        //                    exit;
                        //                }

                        $tplGroup = $this->group($group);
                        $fields = [];
                        foreach ($group->field as $field) {
                            $fields[] = $this->field(
                                $field,
                                $module,
                                $section->attributes()->id,
                                $group->attributes()->id,
                                $moduleDefaultConfig,
                                $moduleDefaultConfigFlat
                            );
                            $pathVariables[] = $this->pathVarFromField(
                                $field,
                                $module,
                                $section->attributes()->id,
                                $group->attributes()->id,
                                $moduleDefaultConfig,
                                $moduleDefaultConfigFlat
                            );
                        }
                        $tplGroup = str_replace('{{fields}}', implode("\n", $fields), $tplGroup);
                        $groups[] = $tplGroup;
                    }
                    $tplSection = str_replace('{{groups}}', implode("\n", $groups), $tplSection);
                    $sections[] = $tplSection;
                }
            }

            $tier3 = $this->getDefaultConfig3Tier($moduleDefaultConfigFlat);
            if (count($tier3) > 0) {
                $sections[] = "\n// Hidden Configuration, may be visible somewhere else ...\n";
            }
            foreach ($tier3 as $sectionID => $group) {
                $tplSection = $this->sectionHidden($sectionID);
                $groups = [];
                foreach ($group as $groupID => $field) {
                    $tplGroup = $this->groupHidden($groupID);
                    $fields = [];
                    foreach ($field as $fieldID => $value) {
                        $fields[] = $this->fieldHidden($sectionID, $groupID, $fieldID, $value);
                    }
                    $tplGroup = str_replace('{{fields}}', implode("\n", $fields), $tplGroup);
                    $groups[] = $tplGroup;
                }
                $tplSection = str_replace('{{groups}}', implode("\n", $groups), $tplSection);
                $sections[] = $tplSection;
            }

            $all = implode('', $sections);
            $sm = strtolower($module);
            file_put_contents('zcode/config_' . $sm . '.go', '
        // +build ignore

        package ' . $sm . '

        import (
            "github.com/corestoreio/csfw/config/element"
            "github.com/corestoreio/csfw/store/scope"
        )

        // PackageConfiguration global configuration options for this package.
        // Used in frontend and backend. See init() for details.
        var PackageConfiguration   element.SectionSlice

        func init(){
            PackageConfiguration = element.MustNewConfiguration(' . "\n$all" . ')
        }
            ');

            if (count($pathVariables) > 0) {
                file_put_contents("zcode/config_{$sm}_path.go", '
        // +build ignore

        package ' . $sm . '

        import (
            "github.com/corestoreio/csfw/config/model"
        )

        ' . implode("\n", $pathVariables)
                );
            }

        }
        return $ret;
    }

    private function getDefaultConfig($configXML)
    {
        if (!file_exists($configXML)) {
            return [];
        }
        /** @var \Magento\Framework\Xml\Parser $parser */
        $parser = $this->_objectManager->create('\Magento\Framework\Xml\Parser');
        $parser->load($configXML);
        $arr = $parser->xmlToArray();
        if (isset($arr['config']['_value']['default'])) {
            return $arr['config']['_value']['default'];
        }
        return [];
    }

    private function getDefaultConfigFlat(array $config)
    {
        $flat = [];
        foreach ($config as $p1 => $cg) {
            foreach ($cg as $p2 => $cf) {
                if (is_array($cf)) {
                    foreach ($cf as $p3 => $value) {
                        $flat[$p1 . '/' . $p2 . '/' . $p3] = $value;
                    }
                }
            }
        }
        return $flat;
    }

    private function getDefaultConfig3Tier(array $config)
    {
        $ret = [];
        foreach ($config as $path => $value) {
            $t = explode('/', $path);
            $ret[$t[0]][$t[1]][$t[2]] = $value;
        }
        return $ret;
    }

    private function fieldHidden($sectionID, $groupID, $fieldID, $default)
    {

        if (is_numeric($default)) {
            $intDefault = (int)$default;
            if ($intDefault === 1 || $intDefault === 0) {
                $default = $intDefault === 1 ? 'true' : 'false';
            }
        } elseif (empty($default)) {
            $default = '';
        } elseif (is_array($default)) {
            $default = '`' . json_encode($default) . '`';
        } else {
            $default = "`$default`";
        }

        $path = $pathOrg = $sectionID . '/' . $groupID . '/' . $fieldID;

        $ret = ['&element.Field{'];
        $ret[] = sprintf('// Path: %s', $path);
        $ret[] = sprintf('ID:      `%s`,', $fieldID);
        $ret[] = 'Type:     element.TypeHidden,';
        $ret[] = 'Visible: element.VisibleNo,';
        if (false === empty($default)) {
            $ret[] = sprintf('Default: %s,', $default);
        }
        $ret[] = '},';
        return $this->myImplode($ret);
    }

    private function field(\SimpleXMLElement $f, $module, $sID, $gID, array $moduleDefaultConfig, array &$moduleDefaultConfigFlat)
    {
        $default = '';
        $backendModel = '';
        $sourceModel = '';

        if ($f->backend_model) {
            $backendModel .= $f->backend_model;
        }
        if ($f->source_model) {
            $sourceModel .= $f->source_model;
        }

        $path = $pathOrg = $sID . '/' . $gID . '/' . $f->attributes()->id;
        if (trim($f->config_path) !== '') {
            $path = $f->config_path;
        }

        if (isset($moduleDefaultConfig[(string)$sID])) {
            $sec = @$moduleDefaultConfig[(string)$sID];
            $grou = @$sec[(string)$gID];
            $default = @$grou[(string)$f->attributes()->id];
        }

        $type = 'element.Type' . ucfirst($f->attributes()->type);
        if (strpos($type, '\\') !== false) {
            $type = 'element.TypeCustom, // @todo: ' . ucfirst($f->attributes()->type);
        }

        if (is_numeric($default)) {
            $intDefault = (int)$default;
            if ($type === 'element.TypeSelect' && ($intDefault === 1 || $intDefault === 0)) {
                $default = $intDefault === 1 ? 'true' : 'false';
            }
            unset($moduleDefaultConfigFlat[$path]);
        } elseif (true === empty($default)) {
            $default = '';
        } elseif (true === is_array($default)) {
            if (isset($default['_attribute']['backend_model'])) {
                $backendModel .= ' @todo ' . $default['_attribute']['backend_model'];
                $default = 'nil';
            } elseif (isset($default['_attribute']['source_model'])) {
                $sourceModel .= ' @todo ' . $default['_attribute']['source_model'];
                $default = 'nil';
            } else {
                $default = '`' . json_encode($default) . '`';
            }
            unset($moduleDefaultConfigFlat[$path]);
        } else {
            $default = "`$default`";
            unset($moduleDefaultConfigFlat[$path]);
        }

        $ret = ['&element.Field{'];
        if ($path !== $pathOrg) {
            $ret[] = sprintf('ConfigPath: `%s`, // Original: %s', $path, $pathOrg);
        } else {
            $ret[] = sprintf('// Path: %s', $path);
        }
        $ret[] = sprintf('ID:      "%s",', $f->attributes()->id);
        if ('' !== trim($f->label)) {
            $ret[] = sprintf('Label:   `%s`,', $f->label);
        }
        if ('' !== trim($f->comment)) {
            $ret[] = sprintf('Comment: element.LongText(`%s`),',$this-> flattenString($f->comment));
        }
        if ('' !== trim($f->tooltip)) {
            $ret[] = sprintf('Tooltip: element.LongText(`%s`),', $this->flattenString($f->tooltip));
        }
        $ret[] = sprintf('Type:     %s,', $type);
        if ((int)$f->attributes()->sortOrder > 0) {
            $ret[] = sprintf('SortOrder: %d,', (int)$f->attributes()->sortOrder);
        }
        $ret[] = 'Visible: element.VisibleYes,';

        $scope = $this->scope($f);
        if ('' !== $scope) {
            $ret[] = sprintf('Scope:   %s,', $scope);
        }
        if ((int)$f->can_be_empty === 1) {
            $ret[] = 'CanBeEmpty: true,';
        }
        if (false === empty($default)) {
            $ret[] = sprintf('Default: %s,', $default);
        }
        if (false === empty($backendModel)) {
            $ret[] = sprintf('// BackendModel: %s', $backendModel);
        }
        if (false === empty($sourceModel)) {
            $ret[] = sprintf('// SourceModel: %s', $sourceModel);
        }
        $ret[] = '},';
        return $this->myImplode($ret);
    }

    private function group(\SimpleXMLElement $g)
    {
        $ret = ['&element.Group{'];
        $ret[] = 'ID:    "' . $g->attributes()->id . '",';
        if (trim($g->label) !== '') {
            $ret[] = 'Label:    `' . $this->flattenString($g->label) . '`,';
        }
        if (trim($g->comment) !== '') {
            $ret[] = 'Comment:    element.LongText(`' . $this->flattenString($g->comment) . '`),';
        }
        if ((int)$g->attributes()->sortOrder > 0) {
            $ret[] = 'SortOrder:    ' . intval($g->attributes()->sortOrder) . ',';
        }
        $scope = $this->scope($g);
        if ('' !== $scope) {
            $ret[] = 'Scope:    ' . $scope . ',';
        }
        if (trim($g->help_url) !== '') {
            $ret[] = 'HelpURL:    element.LongText(`' . $this->flattenString($g->help_url) . '`),';
        }
        if (trim($g->more_url) !== '') {
            $ret[] = 'MoreURL:    element.LongText(`' . $this->flattenString($g->more_url) . '`),';
        }
        if (trim($g->demo_link) !== '') {
            $ret[] = 'DemoLink:    element.LongText(`' . $this->flattenString($g->demo_link) . '`),';
        }
        if ((int)$g->hide_in_single_store_mode === 1) {
            $ret[] = 'HideInSingleStoreMode:    true,';
        }

        $ret[] = "Fields: element.NewFieldSlice(\n{{fields}}\n),";
        $ret[] = '},';

        return $this->myImplode($ret);
    }


    private function groupHidden($id)
    {
        return sprintf('&element.Group{
    				ID:    "%s",
    				Fields: element.NewFieldSlice(
    				    {{fields}}
    				),
    			},
        ',
            $id
        );
    }

    private function section(\SimpleXMLElement $s)
    {
        $ret = ['&element.Section{'];

        $ret[] = 'ID:    "' . $s->attributes()->id . '",';
        if (trim($s->label) !== '') {
            $ret[] = 'Label:    `' . $this->flattenString($s->label) . '`,';
        }
        if ((int)$s->attributes()->sortOrder > 0) {
            $ret[] = 'SortOrder:    ' . intval($s->attributes()->sortOrder) . ',';
        }
        $scope = $this->scope($s);
        if ('' !== $scope) {
            $ret[] = 'Scope:    ' . $scope . ',';
        }
        if (trim($s->resource) !== '') {
            $ret[] = 'Resource:  0,  // ' . $s->resource;
        }

        $ret[] = "Groups: element.NewGroupSlice(\n{{groups}}\n),";
        $ret[] = '},';
        return $this->myImplode($ret);
    }

    private function sectionHidden($id)
    {
        return sprintf('&element.Section{
    		ID: "%s",
    		Groups: element.NewGroupSlice(
    		    {{groups}}
    		),
    	},
        ',
            $id
        );
    }


    private function pathVarFromField(\SimpleXMLElement $f, $module, $sID, $gID, array $moduleDefaultConfig, array &$moduleDefaultConfigFlat)
    {

        $backendModel = '';
        $sourceModel = '';

        if ($f->backend_model) {
            $backendModel .= $f->backend_model;
        }
        if ($f->source_model) {
            $sourceModel .= $f->source_model;
        }

        $path = $pathOrg = $sID . '/' . $gID . '/' . $f->attributes()->id;
        if (trim($f->config_path) !== '') {
            $path = $f->config_path;
        }
        $pathUnderScore = str_replace('/', '_', $path);
        $ret = [];
        $ret[] = '// Path' . $this->snakeCaseToUpperCamelCase($pathUnderScore) . ' => ' . $this->flattenString($f->label) . '.';
        $comment = $this->flattenString($f->comment);
        if ($comment !== '') {
            foreach ($this->filterComment($comment) as $c) {
                $ret[] = '// ' . $c;
            }
        }
        if (false === empty($backendModel)) {
            $ret[] = sprintf('// BackendModel: %s', $backendModel);
        }
        if (false === empty($sourceModel)) {
            $ret[] = sprintf('// SourceModel: %s', $sourceModel);
        }

        $model = 'NewStr';
        $type = trim($f->attributes()->type);
        if ($type === 'select' && (strpos($sourceModel, 'esno') !== false || strpos($sourceModel, 'nabledisa') !== false)) {
            $model = 'NewBool';
        } elseif ($type === 'multiselect') {
            $model = 'NewStringCSV';
        }

        $ret[] = 'var Path' . $this->snakeCaseToUpperCamelCase($pathUnderScore) . ' = model.' . $model . '(`' . $path . '`)';

        return $this->myImplode($ret);
    }

    private function scope(\SimpleXMLElement $s)
    {
        $scope = [];
        if ((string)$s->attributes()->showInDefault === '1') {
            $scope[] = 'scope.DefaultID';
        }
        if ((string)$s->attributes()->showInWebsite === '1') {
            $scope[] = 'scope.WebsiteID';
        }
        if ((string)$s->attributes()->showInStore === '1') {
            $scope[] = 'scope.StoreID';
        }
        if (count($scope) === 3) {
            return 'scope.PermAll';
        }
        if (count($scope) < 1) {
            return '';
        }
        return 'scope.NewPerm(' . implode(',', $scope) . ')';
    }

    private function flattenString($comment)
    {
        return preg_replace('~\s+~', ' ', trim($comment));
    }

    private function filterComment($comment)
    {
        return explode("\n", wordwrap(strip_tags($comment), 75, "\n"));
    }

    private function myImplode(array $a)
    {
        $str = implode($a, "\n") . "\n";
        return str_replace('Magento', 'Otnegam', $str);
    }

    private function snakeCaseToUpperCamelCase($input)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $input)));
    }
}
