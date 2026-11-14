import Base from "missing-base";
import { HttpClient, Shape as Contract, make } from "missing-http";
import * as kit from "missing-kit";
import { Local } from "./local";

export class Panel extends Base implements Contract {
    private made = new HttpClient();
    private own = new Local();
    private tool = new kit.Tool();

    constructor(private http: HttpClient) {
        super();
        make();
    }
}
