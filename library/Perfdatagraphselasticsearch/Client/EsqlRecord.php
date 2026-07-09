<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

/**
 * EsqlRecord represents a single CSV line
 */
class EsqlRecord
{
    protected string $recordType;
    protected string $label;
    protected int $timestamp;
    protected ?float $value;
    protected ?float $warning;
    protected ?float $critical;
    protected ?string $unit;

    public function __construct(
        string $recordType,
        string $label,
        int $timestamp,
        ?float $value,
        ?float $warn,
        ?float $crit,
        ?string $unit,
    ) {
        $this->recordType = $recordType;
        $this->label = $label;
        $this->timestamp = $timestamp;
        $this->value = $value;
        $this->warning = $warn;
        $this->critical = $crit;
        $this->unit = $unit;
    }

    public function getRecordType(): string
    {
        return $this->recordType;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function getWarning(): ?float
    {
        return $this->warning;
    }

    public function getCritical(): ?float
    {
        return $this->critical;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }
}
