export interface InvoiceRow {
  id: string;
  totalCents: number;
  status: 'open' | 'closed';
}

export class Db {
  query<T>(sql: string): Promise<T[]> {
    throw new Error('not implemented');
  }
}
