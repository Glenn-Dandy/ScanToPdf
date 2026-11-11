<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method void setCreated(int $created)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getFolder()
 * @method void setFolder(string $folder)
 * @method int|null getDpi()
 * @method void setDpi(?int $dpi)
 * @method string|null getPaper()
 * @method void setPaper(?string $paper)
 * @method int|null getSheets()
 * @method void setSheets(?int $sheets)
 * @method int getPages()
 * @method void setPages(int $pages)
 * @method void setFileId(?int $fileId)
 * @method void setFileName(?string $fileName)
 * @method void setMessage(?string $message)
 */
class Job extends Entity implements JsonSerializable {
    public const STATUS_WAITING = 'waiting';
    public const STATUS_SCANNING = 'scanning';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    protected $userId = '';
    protected $created = 0;
    protected $status = '';
    protected $kind = '';
    protected $folder;
    protected $dpi;
    protected $paper;
    protected $sheets;
    protected $pages = 0;
    protected $fileId;
    protected $fileName;
    protected $message;

    public function __construct() {
        $this->addType('created', Types::BIGINT);
        $this->addType('pages', Types::INTEGER);
        $this->addType('dpi', Types::INTEGER);
        $this->addType('sheets', Types::INTEGER);
        $this->addType('fileId', Types::BIGINT);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'created' => $this->created,
            'userId' => $this->userId,
            'kind' => $this->kind,
            'folder' => $this->folder,
            'status' => $this->status,
            'pages' => $this->pages,
            'fileId' => $this->fileId,
            'fileName' => $this->fileName,
            'message' => $this->message,
        ];
    }
}
