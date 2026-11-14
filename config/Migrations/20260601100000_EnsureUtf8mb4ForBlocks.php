<?php
declare(strict_types=1);

use App\Migrations\Utf8mb4CollationTrait;
use Migrations\BaseMigration;

class EnsureUtf8mb4ForBlocks extends BaseMigration
{
    use Utf8mb4CollationTrait;

    public function change(): void
    {
        $collation = $this->getUtf8mb4Collation();
        $this->execute("ALTER TABLE fcs_blocks CONVERT TO CHARACTER SET utf8mb4 COLLATE $collation;");
        $this->execute("ALTER TABLE fcs_header_promos CONVERT TO CHARACTER SET utf8mb4 COLLATE $collation;");
    }
}
