import { Adapter, MysqlAdapter, PostgresAdapter } from './adapters'

/** Reaches `destroyPool` through a cast the base type does not declare. */
export async function closeAll(): Promise<void> {
  const adapters: Adapter[] = [new PostgresAdapter(), new MysqlAdapter()]
  for (const adapter of adapters) {
    await adapter.query('SELECT 1')
    const poolable = adapter as unknown as { destroyPool?: () => Promise<void> }
    if (typeof poolable.destroyPool === 'function') await poolable.destroyPool()
  }
}
