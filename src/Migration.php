<?php declare(strict_types=1);

namespace Pgsafari;

final class Migration {
  public int $start;
  /** @var InTransition[] */
  public array $transitions;

  /**
   * @param int $start
   * @param InTransition[] $transitions
   */
  final public function __construct(int $start, array $transitions) {
    $this->start = $start;
    $this->transitions = $transitions;
  }

  /**
   * @throws \Exception
   */
  final public function exec(\PDO $pdo): void {
    if (!$pdo->beginTransaction()) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to begin transaction: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
    $start = $this->start;
    foreach($this->transitions as $transition) {
      $this->txTransition($pdo, $start, $transition->end, $transition->script);
      $start = $transition->end;
    }
    if (!$pdo->commit()) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to commit transaction: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
  }

  /**
   * @throws \Exception
   */
  private function txTransition(\PDO $pdo, int $start, int $end, string $script): void {
    $meta = SchemaMeta::txRead($pdo);
    $oldVersion = $meta !== null ? $meta->version : 0;
    if ($oldVersion !== $start) {
      throw new \Exception("expected start version: " . $start . ", actual: " . $oldVersion);
    }
    $res = $pdo->exec($script);
    if ($res === false) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to apply transition " . $start . " -> " . $end . " : " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
    $meta = new SchemaMeta($end);
    SchemaMeta::txWrite($pdo, $meta);
  }
}
