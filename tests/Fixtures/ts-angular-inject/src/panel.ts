import { inject, inject as ask } from "@angular/core";
import { inject as own } from "./own";
import * as services from "./services";
import { Api, Config, Logger, Shared, Store, Unused, API_URL } from "./services";

declare function local(token: unknown): unknown;
declare function mark(token: unknown): PropertyDecorator;

export class Panel {
    private api = inject(Api);
    readonly store = ask(Store, { optional: true });
    private qualified = inject(services.Unused);
    private url = inject(API_URL);
    private literal = inject("literal" as never);
    private mine = own(Unused);
    private plain = local(Unused);
    private later = () => inject(Unused);
    private shared = inject(Shared);
    private config = inject(Config as never);
    private named = inject(Config);
    @mark(inject(Unused)) marked!: unknown;
    private log;

    constructor(private given: Logger) {
        this.log = inject(Logger);
    }

    load() {
        return inject(Unused);
    }
}
