<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000300Date20261108230000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('scantopdf_jobs');
        if ($table->hasColumn('dpi')) {
            return null;
        }
        $table->addColumn('dpi', Types::INTEGER, ['notnull' => false]);

        return $schema;
    }
}
