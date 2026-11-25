<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;

class PagesRebuildSlugsCommand extends Command
{
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->setDescription('Berechnet die Adressen (slug) aller Seiten neu.');
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $count = TableRegistry::getTableLocator()->get('Pages')->rebuildAllSlugs();
        $io->out('Adressen aktualisiert: ' . $count);
        return static::CODE_SUCCESS;
    }
}
