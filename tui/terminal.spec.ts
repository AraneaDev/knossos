import { describe, expect, it } from 'vitest'
import { ENTER, LEAVE, terminalMode } from './terminal'

const fake = () => {
  const events: string[] = []
  const stdin = { setRawMode: (on: boolean) => events.push(`raw:${on}`) } as unknown as NodeJS.ReadStream
  return { events, mode: terminalMode({ stdin, write: bytes => events.push(bytes) }) }
}

describe('terminalMode', () => {
  it('writes LEAVE once however often it leaves', () => {
    const { events, mode } = fake()
    mode.enter()
    mode.leave()
    mode.leave()
    expect(events.filter(e => e === LEAVE)).toHaveLength(1)
  })

  it('sets raw mode on entering and off on leaving, around the sequences', () => {
    const { events, mode } = fake()
    mode.enter()
    mode.leave()
    expect(events).toEqual(['raw:true', ENTER, LEAVE, 'raw:false'])
  })

  it('writes nothing for a leave that never entered, and enters again after a leave', () => {
    const { events, mode } = fake()
    mode.leave()
    expect(events).toEqual([])
    mode.enter()
    mode.leave()
    mode.enter()
    expect(events.filter(e => e === ENTER)).toHaveLength(2)
  })
})
