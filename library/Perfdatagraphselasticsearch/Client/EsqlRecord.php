<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Client;

/**
 * EsqlRecord represents a single CSV line
 */
class EsqlRecord
{
    protected string $Id;
    protected string $label;
    protected int $timestamp;
    protected ?float $value = null;
    protected ?float $warning = null;
    protected ?float $critical = null;
    protected string $unit = '';

    public function __construct(
        string $Id,
        string $label,
        int $timestamp,
    ) {
        $this->Id = $Id;
        $this->label = $label;
        $this->timestamp = $timestamp;
    }

    public function getId(): string
    {
        return $this->Id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function setValue(float $value): void
    {
        $this->value = $value;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function setWarning(float $warning): void
    {
        $this->warning = $warning;
    }

    public function getWarning(): ?float
    {
        return $this->warning;
    }

    public function setCritical(float $critical): void
    {
        $this->critical = $critical;
    }

    public function getCritical(): ?float
    {
        return $this->critical;
    }

    public function setUnit(string $unit): void
    {
        $this->unit = $unit;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }
}
