<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Support;

final class ResolvedStockImportFile
{
    /**
     * @param  resource|null  $temporaryHandle
     */
    public function __construct(
        public readonly string $path,
        private $temporaryHandle = null,
    ) {}

    public function release(): void
    {
        if (is_resource($this->temporaryHandle)) {
            fclose($this->temporaryHandle);
            $this->temporaryHandle = null;
        }
    }
}
