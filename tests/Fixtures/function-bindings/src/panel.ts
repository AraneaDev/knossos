import { exportedArrow } from "./bindings";

export class Panel {
    label = "panel";

    private load = async () => {
        exportedArrow();
    };

    handle = function () {
        return exportedArrow();
    };

    render() {
        return this.load();
    }
}
