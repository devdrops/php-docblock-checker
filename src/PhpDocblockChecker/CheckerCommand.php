<?php

/**
 * PHP Docblock Checker
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/devdrops/php-docblock-checker/blob/master/LICENSE.md
 */

namespace PhpDocblockChecker;

use DirectoryIterator;
use PHP_Token_Stream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to check a directory of PHP files for Docblocks.
 * @author Dan Cryer <dan@block8.co.uk>
 */
class CheckerCommand extends Command
{
    /**
     * @var string
     */
    protected $basePath;
    /**
     * @var bool
     */
    protected $verbose = true;
    /**
     * @var array
     */
    protected $report = [];
    /**
     * @var array
     */
    protected $exclude = [];
    /**
     * @var bool
     */
    protected $skipClasses = false;
    /**
     * @var bool
     */
    protected $skipMethods = false;
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('check')
            ->setDescription('Check PHP files within a directory for appropriate use of Docblocks.')
            ->addOption('exclude', 'x', InputOption::VALUE_REQUIRED, 'Files and directories to exclude.', null)
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Directory to scan.', null)
            ->addOption('skip-classes', null, InputOption::VALUE_NONE, 'Don\'t check classes for docblocks.')
            ->addOption('skip-methods', null, InputOption::VALUE_NONE, 'Don\'t check methods for docblocks.')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output JSON instead of a log.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Process options:
        $exclude = $input->getOption('exclude');
        $json = $input->getOption('json');
        $this->basePath = $input->getOption('directory');
        $this->verbose = !$json;
        $this->output = $output;
        $this->skipClasses = $input->getOption('skip-classes');
        $this->skipMethods = $input->getOption('skip-methods');

        if (is_null($this->basePath)) {
            $this->output->writeln('<error>Please provide the path to check.</error>');
            
            return 0;
        }
        
        if (substr($this->basePath, -1) != '/') {
            $this->basePath .= '/';
        }
        
        if (!is_null($exclude)) {
            $this->exclude = array_map('trim', explode(',', $exclude));
        }

        $this->output->writeln('<comment>Checking...</comment>');
        
        $this->processDirectory();

        if ($json) {
            print json_encode($this->report);
        }

        $this->output->writeln('<comment>Done!</comment>');
        
        $this->output->writeln(
            'Found <fg=red;options=bold>'
            .count($this->report).'</fg=red;options=bold> violation(s).'
        );
        
        return count($this->report) ? 1 : 0;
    }

    /**
     * @param string $path
     */
    protected function processDirectory($path = '')
    {
        $dir = new DirectoryIterator($this->basePath.$path);

        foreach ($dir as $item) {
            if ($item->isDot()) {
                continue;
            }

            $itemPath = $path.$item->getFilename();

            if (in_array($itemPath, $this->exclude)) {
                continue;
            }

            if ($item->isFile() && $item->getExtension() == 'php') {
                $this->processFile($itemPath);
            }

            if ($item->isDir()) {
                $this->processDirectory($itemPath.'/');
            }
        }
    }

    /**
     * @param string $file
     */
    protected function processFile($file)
    {
        $stream = new PHP_Token_Stream($this->basePath.$file);

        foreach ($stream->getClasses() as $name => $class) {
            $errors = false;

            if (!$this->skipClasses && is_null($class['docblock'])) {
                $errors = true;

                $this->report[] = array(
                    'type' => 'class',
                    'file' => $file,
                    'class' => $name,
                    'line' => $class['startLine'],
                );

                if ($this->verbose) {
                    $message = $class['file'].':L'
                            .$class['startLine']
                            .' - <comment>Class '.$name." is missing it's docblock.</comment>";
                    $this->output->writeln(
                        '    <fg=red;options=bold>ERROR</fg=red;options=bold> '.$message
                    );
                }
            }

            if (!$this->skipMethods) {
                foreach ($class['methods'] as $methodName => $method) {
                    if ($methodName == 'anonymous function') {
                        continue;
                    }

                    if (is_null($method['docblock'])) {
                        $errors = true;

                        $this->report[] = array(
                            'type' => 'method',
                            'file' => $file,
                            'class' => $name,
                            'method' => $methodName,
                            'line' => $method['startLine'],
                        );

                        if ($this->verbose) {
                            $message = $class['file'].':L'
                                    .$method['startLine'].' - <comment>Method '
                                    .$name.'::'.$methodName." is missing it's docblock.</comment>";
                            $this->output->writeln(
                                '    <fg=red;options=bold>ERROR</fg=red;options=bold> '.$message
                            );
                        }
                    }
                }
            }

            if (!$errors && $this->verbose) {
                $this->output->writeln(
                    '    <fg=green;options=bold>OK</fg=green;options=bold> '
                    .$class['package']['namespace'].'\\'.$name
                );
            }
        }
    }
}
