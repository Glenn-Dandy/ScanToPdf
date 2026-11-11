<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000400Date20261109130000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('scantopdf_jobs');
        if ($table->hasColumn('paper')) {
            return null;
        }
        $table->addColumn('paper', Types::STRING, ['notnull' => false, 'length' => 16]);
        $table->addColumn('sheets', Types::INTEGER, ['notnull' => false]);

        return $schema;
    }
}
