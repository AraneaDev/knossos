export class CatalogService {
  removeBySku(sku: string): boolean {
    return sku.length > 0;
  }
}
