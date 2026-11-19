export class InvoiceService {
  totalCents(): number {
    return 0;
  }

  label(): string {
    return '';
  }

  fetch(): Promise<string> {
    return Promise.resolve('');
  }
}
