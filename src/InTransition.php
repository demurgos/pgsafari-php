<?php declare(strict_types=1);

namespace Pgsafari;

final class InTransition {
  final public function __construct(public int $end, public string $script) {}
}
