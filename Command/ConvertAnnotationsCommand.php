<?php

namespace W3com\BoomBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use W3com\BoomBundle\Migration\AnnotationConverter;

final class ConvertAnnotationsCommand extends Command
{
    public function __construct(private array $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('boom:convert:annotations')
            ->setDescription('Convert legacy BOOM entity annotations to PHP attributes.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Entity directory or PHP file (defaults to entity_directory).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report changes without writing files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path') ?? $this->config['entity_directory'];
        if (is_link($path) || (!is_dir($path) && (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php'))) {
            $io->error('The path must be an existing directory or PHP file, and must not be a symbolic link.');
            return Command::FAILURE;
        }
        $files = is_dir($path) ? (new Finder())->files()->name('*.php')->in($path)->sortByName() : [new \SplFileInfo($path)];
        $converter = new AnnotationConverter();
        $changed = 0;
        $errors = 0;
        foreach ($files as $file) {
            $filename = $file->getPathname();
            try {
                if ($file->isLink()) throw new \RuntimeException('Symbolic links are not converted.');
                $original = file_get_contents($filename);
                if ($original === false) throw new \RuntimeException('Cannot read file.');
                $converted = $converter->convert($original);
                if ($converted === $original) continue;
                if (!$input->getOption('dry-run')) $this->writeFile($filename, $converted);
                ++$changed;
                $io->writeln(($input->getOption('dry-run') ? 'Would convert: ' : 'Converted: ') . $filename);
            } catch (\Throwable $e) {
                ++$errors;
                $io->error($filename . ': ' . $e->getMessage());
            }
        }
        $io->writeln(sprintf('%d file(s) %s; %d error(s).', $changed, $input->getOption('dry-run') ? 'to convert' : 'converted', $errors));
        if ($changed && !$input->getOption('dry-run')) $io->note('Clear BOOM metadata caches before using the converted entities.');
        return $errors ? Command::FAILURE : Command::SUCCESS;
    }

    private function writeFile(string $filename, string $contents): void
    {
        $temporary = tempnam(dirname($filename), '.boom-');
        if ($temporary === false) throw new \RuntimeException('Cannot create temporary file.');
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents)) throw new \RuntimeException('Cannot write temporary file.');
            $permissions = fileperms($filename);
            if ($permissions === false || !chmod($temporary, $permissions & 0777)) throw new \RuntimeException('Cannot preserve file permissions.');
            if (!rename($temporary, $filename)) throw new \RuntimeException('Cannot replace file.');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
