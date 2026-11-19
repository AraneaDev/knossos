import { Db, InvoiceRow } from './db';

export class LedgerService {
  constructor(private readonly db: Db) {}

  async totalFor(id: string): Promise<InvoiceRow> {
    const rows = await this.db.query<InvoiceRow>('select * from invoices where id = ?', id);
    return rows[0]!;
  }
}
