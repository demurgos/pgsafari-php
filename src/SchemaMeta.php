<?php declare(strict_types=1);

namespace Pgsafari;

final class SchemaMeta implements \JsonSerializable {
  public int $version;

  final public function __construct(int $version) {
    $this->version = $version;
  }

  final public function jsonSerialize(): array {
    return [
      "version" => $this->version,
    ];
  }

  final public static function txWrite(\PDO $pdo, self $meta): void {
    $queries = [
      "DROP FUNCTION IF EXISTS get_schema_meta;",
      "DROP TYPE IF EXISTS schema_meta;",
      "DROP TYPE IF EXISTS raw_schema_meta;",
      "CREATE TYPE raw_schema_meta AS (version int4);",
      "CREATE DOMAIN schema_meta AS raw_schema_meta CHECK ((value).version IS NOT NULL AND (value).version >= 0);",
      "CREATE FUNCTION get_schema_meta() RETURNS schema_meta LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$ SELECT ROW(" . $meta->version . "); $$;",
    ];
    foreach ($queries as $query) {
      $res = $pdo->exec($query);
      if ($res === false) {
        $info = $pdo->errorInfo();
        throw new \Exception("failed to create transaction savepoint: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
      }
    }
  }

  final public static function read(\PDO $pdo): ?self {
    if (!$pdo->beginTransaction()) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to begin transaction: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
    $meta = self::txRead($pdo);
    if (!$pdo->commit()) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to commit transaction: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
    return $meta;
  }

  /**
   * Read while a transaction is active
   *
   * @throws \Exception
   */
  final public static function txRead(\PDO $pdo): ?self {
    $res = $pdo->exec("SAVEPOINT try_get_meta;");
    if ($res === false) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to create transaction savepoint: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
    try {
      $stmt = $pdo->query("SELECT version FROM get_schema_meta();");
    } catch (\PDOException $e) {
      if($e->getCode() !== "42883") {
        throw $e;
      }
      $res = $pdo->exec("ROLLBACK TO SAVEPOINT try_get_meta;");
      if ($res === false) {
        $info = $pdo->errorInfo();
        throw new \Exception("failed to rollback to transaction savepoint: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
      }
      return null;
    }
    if ($stmt === false) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to exec schema meta query: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
    $rowCount = $stmt->rowCount();
    if ($rowCount !== 1) {
      throw new \Exception("row count: actual = " . $rowCount . ", expected = 1");
    }
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row === false) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to fetch schema meta: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
    $meta = self::jsonDeserialize($row);
    $res = $pdo->exec("RELEASE SAVEPOINT try_get_meta;");
    if ($res === false) {
      $info = $pdo->errorInfo();
      throw new \Exception("failed to release transaction savepoint: " . $info[0] . ", " . $info[1] . ", " . $info[2]);
    }
    return $meta;
  }

  final public static function jsonDeserialize(array $raw): self {
    return new self($raw["version"]);
  }

  /**
   * @param string $json
   * @return self
   * @throws \JsonException
   */
  final public static function fromJson(string $json): self {
    return self::jsonDeserialize(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
  }
}
