export interface ShippingAddress {
  street: string;
  city: string;
}

export interface ConsignmentRecord {
  reference: string;
  weightKg: number;
  destination: ShippingAddress;
  stops: ShippingAddress[];
}
