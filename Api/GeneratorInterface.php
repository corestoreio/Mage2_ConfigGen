<?php


namespace CoreStore\ConfigGen\Api;

interface GeneratorInterface
{
    /**
     * Triggers the process to create all necessary files
     * @return array
     */
    public function generate();

}
