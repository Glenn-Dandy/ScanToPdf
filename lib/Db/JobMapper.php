<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Job> */
class JobMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'scantopdf_jobs', Job::class);
    }

    public function findById(int $id): Job {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function findForUser(int $id, string $userId): Job {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntity($qb);
    }

    /** @return Job[] */
    public function findLatest(int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /** A scanner can only do one thing at a time. Jobs older than $since count as abandoned. */
    public function hasActiveJob(int $since): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter([Job::STATUS_WAITING, Job::STATUS_SCANNING], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->gt('created', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $found = $result->fetchOne();
        $result->closeCursor();
        return $found !== false;
    }
}
