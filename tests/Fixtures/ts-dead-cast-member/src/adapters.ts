export abstract class Adapter {
  abstract query(sql: string): Promise<unknown>
}

export class PostgresAdapter extends Adapter {
  async query(sql: string): Promise<unknown> {
    return sql
  }

  async destroyPool(): Promise<void> {}

  /** Named by no cast and called by nothing: dead. */
  async unused(): Promise<void> {}
}

export class MysqlAdapter extends Adapter {
  async query(sql: string): Promise<unknown> {
    return sql
  }

  async destroyPool(): Promise<void> {}
}
