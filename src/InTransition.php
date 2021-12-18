<?php declare(strict_types=1);

namespace Pgsafari;

final class InTransition {
  public int $end;
  public string $script;

  final public function __construct(int $end, string $script) {
    $this->end = $end;
    $this->script = $script;
  }
}
