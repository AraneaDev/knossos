export interface InvoiceRow { id: string; total: number }
export interface Db { query<T>(sql: string, ...args: unknown[]): Promise<T[]> }
