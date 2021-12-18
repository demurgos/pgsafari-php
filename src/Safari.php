<?php declare(strict_types=1);

namespace Pgsafari;

final class Safari {
  /** @var array<int, bool> */
  private array $versions;
  /** @var array<int, array<int, string>> */
  private array $transitions;
  private string $drop;
  private int $latest;

  final private function __construct(string $drop, array $versions, array $transitions, int $latest) {
    foreach (array_keys($versions) as $version) {
      if (!array_key_exists($version, $transitions)) {
        $transitions[$version] = [];
      }
    }
    foreach ($transitions as &$outTransitions) {
      if (!array_key_exists(0, $outTransitions)) {
        $outTransitions[0] = $drop;
      }
    }
    $this->drop = $drop;
    $this->versions = $versions;
    $this->transitions = $transitions;
    $this->latest = $latest;
  }

  final public static function fromDirectory(string $dbDir): self {
    $dropScriptPath = self::joinPath($dbDir, "drop.sql");
    $drop = file_get_contents($dropScriptPath);
    $transitionsDir = self::joinPath($dbDir, "transitions");
    return self::fromTransitionsDirectory($drop, $transitionsDir);
  }

  private static function fromTransitionsDirectory(string $drop, string $transitionsDir): self {
    /** @var array<int, bool> */
    $versions = [];
    /** @var array<int, array<int, string>> */
    $migrations = [];

    $latest = 0;
    $versions[$latest] = true;

    $dirEntries = scandir($transitionsDir);
    if ($dirEntries === false) {
      throw new \Exception("Failed to scan directory: " . $transitionsDir);
    }
    foreach ($dirEntries as $dirEntry) {
      $entryPath = self::joinPath($transitionsDir, $dirEntry);
      if (!is_file($entryPath)) {
        continue;
      }
      $matches = [];
      $matchResult = preg_match("#^([0-9]{1,4})-([0-9]{1,4})\.sql$#", $dirEntry, $matches);
      if ($matchResult === false) {
        throw new \Exception("Match failure: " . preg_last_error_msg());
      } else if ($matchResult !== 1) {
        continue;
      }
      $start = intval($matches[1], 10);
      $end = intval($matches[2], 10);
      $script = file_get_contents($entryPath);
      $versions[$start] = true;
      $versions[$end] = true;
      $migrations[$start][$end] = $script;
      $latest = max($latest, $start, $end);
    }
    return new self($drop, $versions, $migrations, $latest);
  }

  /**
   * Return the version for the empty state.
   *
   * @return int
   */
  final public function emptyVersion(): int {
    return 0;
  }

  /**
   * Return the version for the latest state.
   *
   * @return int
   */
  final public function latestVersion(): int {
    return $this->latest;
  }

  final public function createMigration(int $start, int $end, MigrationDirection $dir): Migration {
    if (!array_key_exists($start, $this->versions)) {
      throw new \Exception("unknown schema version: " . $start);
    }
    if (!array_key_exists($end, $this->versions)) {
      throw new \Exception("unknown schema version: " . $end);
    }

    /** @var array<int, bool> */
    $closedSet = [];
    /** @var array<int, ?int> */
    $parents = [];
    /** @var array<int, int> */
    $costs = [];
    $parents[$start] = null;
    $costs[$start] = 0;

    for(;;) {
      $lowestNode = null;
      $lowestCost = INF;
      foreach ($costs as $node => $cost) {
        if (array_key_exists($node, $closedSet)) {
          continue;
        }
        if ($cost < $lowestCost) {
          $lowestCost = $cost;
          $lowestNode = $node;
        }
      }
      if ($lowestNode === null || $lowestNode === $end) {
        break;
      }
      $cur = $lowestNode;
      $curCost = $lowestCost;
      foreach (array_keys($this->transitions[$cur]) as $nextNode) {
        $newCost = $curCost + $dir->cost($cur, $nextNode);
        $oldCost = $costs[$nextNode] ?? PHP_INT_MAX;
        if ($newCost < $oldCost) {
          $costs[$nextNode] = $newCost;
          $parents[$nextNode] = $cur;
        }
      }
      $closedSet[$cur] = true;
    }


    $path = [];
    $cur = $end;
    while ($cur !== null) {
      if (!array_key_exists($cur, $parents) || $costs[$cur] === INF) {
        throw new \Exception("migration path not found");
      }
      $path[] = $cur;
      $cur = $parents[$cur];
    }
    $path = array_reverse($path);
    $transitions = [];
    for ($i = 1; $i < count($path); $i++) {
      $tmpStart = $path[$i - 1];
      $tmpEnd = $path[$i];
      $script = $this->transitions[$tmpStart][$tmpEnd];
      $transitions[] = new InTransition($tmpEnd, $script);
    }
    return new Migration($start, $transitions);
  }

  final public function empty(\PDO $pdo): string {
    return "hi";
  }

  private static function joinPath(string $base, string $extra): string {
    $paths = [];
    if ($base !== "") {
      $paths[] = $base;
    }
    if ($extra !== "") {
      $paths[] = $extra;
    }

    return preg_replace("#/+#", "/", join("/", $paths));
  }
}
