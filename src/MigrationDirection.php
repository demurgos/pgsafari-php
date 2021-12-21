<?php declare(strict_types=1);

namespace Pgsafari;

final class MigrationDirection {
  final private function __construct(private string $dir, private bool $allowDrop) {}

  final public function cost(int $start, int $end): float {
    if ($start === $end) {
      return 0;
    }
    if ($end === 0 && $this->allowDrop) {
      return 1;
    }
    if ($this->dir === "downgrade") {
      return $start < $end ? INF : 1;
    } else {
      return $start < $end ? 1 : INF;
    }
  }

  final public static function upgrade(): self {
    return new self("upgrade", false);
  }

  final public static function forceUpgrade(): self {
    return new self("upgrade", true);
  }

  final public static function downgrade(): self {
    return new self("downgrade", false);
  }
}
