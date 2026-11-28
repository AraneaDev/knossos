export enum Leg {
  Air = 'air',
  Sea = 'sea',
}

export type Lane = 'slow' | 'fast';

export class Router {
  reroute(to: Leg): boolean { return to !== Leg.Air; }
  lane(to: Lane): boolean { return to === 'fast'; }
}

export function send(router: Router, to: Leg, lane: Lane): boolean {
  return router.reroute(to) && router.lane(lane);
}
