// Importing this installs a resolver for the mod's modules, which import each other without an extension,
// as the engine's bundler resolves them; plain Node needs the `.ts` added.
import { registerHooks } from 'node:module'

registerHooks({
  resolve(specifier, context, nextResolve) {
    try {
      return nextResolve(specifier, context)
    } catch (error) {
      if (/^\.\.?\//.test(specifier) && !/\.[a-z]+$/.test(specifier)) return nextResolve(`${specifier}.ts`, context)
      throw error
    }
  },
})
