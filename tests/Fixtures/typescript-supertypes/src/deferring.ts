import { Screen } from 'untyped-terminal'

// The checker sees the base as `any`, so only the `super` call names the member.
export class Dim extends Screen {
  draw(data: string): void {
    super.draw(data)
  }
  tidy(): void {
    super.reset()
  }
  later(): () => void {
    return () => super.later()
  }
}
