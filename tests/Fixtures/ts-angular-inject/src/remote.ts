import { inject } from "@angular/core";
import { HttpClient } from "@angular/common/http";

export class Remote {
    private http = inject(HttpClient);
}
