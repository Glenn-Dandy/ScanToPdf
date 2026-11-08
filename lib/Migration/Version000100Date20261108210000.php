<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20261108210000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('scantopdf_jobs')) {
            return null;
        }

        $table = $schema->createTable('scantopdf_jobs');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('created', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('folder', Types::STRING, ['notnull' => true, 'length' => 1000]);
        $table->addColumn('pages', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('file_id', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('file_name', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('message', Types::STRING, ['notnull' => false, 'length' => 1000]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['status', 'created'], 'scantopdf_status');

        return $schema;
    }
}
