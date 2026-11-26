import { Expose } from 'class-transformer';

/** A backed enum: a fixture holds its values. */
export enum Speed {
  Fast = 'fast',
  Slow = 'slow',
}

/** Implicit members: what the wire holds is not known. */
export enum Priority {
  Low,
  High,
}

export interface TripRecord {
  id: string;
  speed: Speed;
  priority: Priority;
  note?: string;
}

export interface CargoRecord {
  id: string;
  weight: number;
  express: boolean;
  tags: string[];
  meta: Record<string, string>;
  sentAt: Date;
  mode: 'air' | 'sea';
}

/** The constructor fills `items`; a fixture need not hold it. */
export class Basket {
  items: string[];
  owner: string;
  count = 0;

  constructor() {
    this.items = [];
  }
}

export interface AddressRecord {
  street: string;
  city: string;
}

export interface RouteRecord {
  reference: string;
  destination: AddressRecord;
  stops: AddressRecord[];
  lookup: Record<string, AddressRecord>;
}

/** Read from a camelCase wire by a converter the scan cannot see. */
export interface SnakeRecord {
  order_id: string;
  placed_at: string;
}

/** Held in a fixture as a map of id to record. */
export interface AccountRecord {
  accountId: string;
  name: string;
  nickname?: string;
}

/** No constructor; its non-public fields are set by its own code, never read from a fixture. */
export class Vault {
  vaultId!: string;
  private hash!: string;
  protected tag!: string;
}

/** class-transformer renames the key: the list is open, and never a candidate. */
export class ExposedRecord {
  @Expose({ name: 'user_name' })
  userName!: string;
}
