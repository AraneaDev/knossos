import { Store } from './store'
import { Watch } from './watch'

let store: Store | null = null

/** The store flows into `Checks` through the function the watch is handed. */
export async function start(): Promise<void> {
  store = new Store()
  await new Watch(() => store).check('main')
}
