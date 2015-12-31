<?php


namespace CoreStore\ConfigGen\Console\Command;

use CoreStore\ConfigGen\Model\Exception\GeneratorException;
use CoreStore\ConfigGen\Api\GeneratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCommand extends Command
{
    /**
     * @var GeneratorInterface
     */
    private $cg;

    /**
     * GenerateCommand constructor.
     * @param GeneratorInterface $configurationGenerator
     */
    public function __construct(GeneratorInterface $configurationGenerator)
    {
        parent::__construct();
        $this->cg = $configurationGenerator;
    }

    protected function configure()
    {
        $this->setName('corestore:generator:config');
        $this->setDescription('Creates Go code from system.xml');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $info = $this->cg->generate();
            if ($info) {
                $this->outputInfo($output, $info);
            } else {
                $output->writeln(sprintf('<comment>Nothing written.</comment>'));
            }
        } catch (GeneratorException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
        }
    }

    /**
     * @param OutputInterface $output
     * @param string[] $info
     */
    private function outputInfo(OutputInterface $output, array $info)
    {
        array_map(function ($line) use ($info, $output) {
            $output->writeln(sprintf('<info>%s</info>', $line));
        }, array_keys($info), $info);
    }
}
