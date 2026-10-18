/**
 * Claude Code's dark and light themes: each theme key's colour, as `#rrggbb`.
 *
 * The pane names its colours by theme key (see palette.ts) and Claude Code
 * resolves them. A host that draws the pane itself, the web page or the
 * preview tool, resolves them here instead, so every host shows the same
 * colour for a key. The raster's dark and light grids are built from these
 * values too.
 *
 * Taken from Claude Code 2.1.288's theme objects, verbatim.
 */

export type WebTheme = 'dark' | 'light'

/** Claude Code 2.1.288's dark and light themes, as `#rrggbb`. */
export const THEMES: Record<WebTheme, Record<string, string>> = {
  dark: {
    autoAccept: '#af87ff', autoAcceptShimmer: '#d0b4ff', skill: '#af87ff', bashBorder: '#fd5db1',
    claude: '#d77757', claudeShimmer: '#eb9f7f', claudeBlue_FOR_SYSTEM_SPINNER: '#93a5ff',
    claudeBlueShimmer_FOR_SYSTEM_SPINNER: '#b1c3ff', permission: '#b1b9f9', permissionShimmer: '#cfd7ff',
    planMode: '#48968c', ide: '#4782c8', promptBorder: '#888888', promptBorderShimmer: '#a6a6a6', text: '#ffffff',
    inverseText: '#000000', inactive: '#999999', inactiveShimmer: '#c1c1c1', subtle: '#505050',
    suggestion: '#b1b9f9', remember: '#b1b9f9', background: '#00cccc', success: '#4eba65', error: '#ff6b80',
    warning: '#ffc107', merged: '#af87ff', warningShimmer: '#ffdf39', diffAdded: '#225c2b',
    diffRemoved: '#7a2936', diffAddedDimmed: '#47584a', diffRemovedDimmed: '#69484d', diffAddedWord: '#38a660',
    diffRemovedWord: '#b3596b', red_FOR_SUBAGENTS_ONLY: '#dc2626', blue_FOR_SUBAGENTS_ONLY: '#6a9bcc',
    green_FOR_SUBAGENTS_ONLY: '#16a34a', yellow_FOR_SUBAGENTS_ONLY: '#ca8a04',
    purple_FOR_SUBAGENTS_ONLY: '#827dbd', orange_FOR_SUBAGENTS_ONLY: '#d97757',
    pink_FOR_SUBAGENTS_ONLY: '#c46686', cyan_FOR_SUBAGENTS_ONLY: '#0891b2', professionalBlue: '#6a9bcc',
    chromeYellow: '#fbbc04', clawd_body: '#d77757', clawd_background: '#000000', userMessageBackground: '#373737',
    userMessageBackgroundHover: '#464646', composerSidebarBackground: '#262626', selectionBg: '#264f78',
    bashMessageBackgroundColor: '#413c41', memoryBackgroundColor: '#374146', rate_limit_fill: '#b1b9f9',
    rate_limit_empty: '#505370', fastMode: '#ff7814', fastModeShimmer: '#ffa546', effortUltra: '#af87ff',
    briefLabelYou: '#7ab4e8', briefLabelClaude: '#d77757', rainbow_red: '#eb5f57', rainbow_orange: '#f58b57',
    rainbow_yellow: '#fac35f', rainbow_green: '#91c882', rainbow_blue: '#82aadc', rainbow_indigo: '#9b82c8',
    rainbow_violet: '#c882b4', rainbow_red_shimmer: '#fa9b93', rainbow_orange_shimmer: '#ffb989',
    rainbow_yellow_shimmer: '#ffe19b', rainbow_green_shimmer: '#b9e6b4', rainbow_blue_shimmer: '#b4cdf0',
    rainbow_indigo_shimmer: '#c3b4e6', rainbow_violet_shimmer: '#e6b4d2',
  },
  light: {
    autoAccept: '#8700ff', autoAcceptShimmer: '#d0b4ff', skill: '#8700ff', bashBorder: '#ff0087',
    claude: '#d77757', claudeShimmer: '#f59575', claudeBlue_FOR_SYSTEM_SPINNER: '#5769f7',
    claudeBlueShimmer_FOR_SYSTEM_SPINNER: '#7587ff', permission: '#5769f7', permissionShimmer: '#899bff',
    planMode: '#006666', ide: '#4782c8', promptBorder: '#8a8a8a', promptBorderShimmer: '#b7b7b7', text: '#000000',
    inverseText: '#ffffff', inactive: '#666666', inactiveShimmer: '#8e8e8e', subtle: '#afafaf',
    suggestion: '#5769f7', remember: '#0000ff', background: '#009999', success: '#2c7a39', error: '#ab2b3f',
    warning: '#966c1e', merged: '#8700ff', warningShimmer: '#c89e50', diffAdded: '#69db7c',
    diffRemoved: '#ffa8b4', diffAddedDimmed: '#c7e1cb', diffRemovedDimmed: '#fdd2d8', diffAddedWord: '#2f9d44',
    diffRemovedWord: '#d1454b', red_FOR_SUBAGENTS_ONLY: '#dc2626', blue_FOR_SUBAGENTS_ONLY: '#6a9bcc',
    green_FOR_SUBAGENTS_ONLY: '#16a34a', yellow_FOR_SUBAGENTS_ONLY: '#ca8a04',
    purple_FOR_SUBAGENTS_ONLY: '#827dbd', orange_FOR_SUBAGENTS_ONLY: '#d97757',
    pink_FOR_SUBAGENTS_ONLY: '#c46686', cyan_FOR_SUBAGENTS_ONLY: '#0891b2', professionalBlue: '#6a9bcc',
    chromeYellow: '#fbbc04', clawd_body: '#d77757', clawd_background: '#000000', userMessageBackground: '#f0f0f0',
    userMessageBackgroundHover: '#fcfcfc', composerSidebarBackground: '#f5f5f5', selectionBg: '#b4d5ff',
    bashMessageBackgroundColor: '#faf5fa', memoryBackgroundColor: '#e6f5fa', rate_limit_fill: '#5769f7',
    rate_limit_empty: '#272f6f', fastMode: '#ff6a00', fastModeShimmer: '#ff9632', effortUltra: '#8700ff',
    briefLabelYou: '#2563eb', briefLabelClaude: '#d77757', rainbow_red: '#eb5f57', rainbow_orange: '#f58b57',
    rainbow_yellow: '#fac35f', rainbow_green: '#91c882', rainbow_blue: '#82aadc', rainbow_indigo: '#9b82c8',
    rainbow_violet: '#c882b4', rainbow_red_shimmer: '#fa9b93', rainbow_orange_shimmer: '#ffb989',
    rainbow_yellow_shimmer: '#ffe19b', rainbow_green_shimmer: '#b9e6b4', rainbow_blue_shimmer: '#b4cdf0',
    rainbow_indigo_shimmer: '#c3b4e6', rainbow_violet_shimmer: '#e6b4d2',
  },
}

/** A segment's colour as `#rrggbb` under `theme`: a theme key's value, or a raw `#rrggbb` as it is; undefined for none or a key the theme lacks. */
export function themeColour(key: string | undefined, theme: WebTheme): string | undefined {
  if (key === undefined) return undefined
  if (/^#[0-9a-f]{6}$/i.test(key)) return key
  return Object.hasOwn(THEMES[theme], key) ? THEMES[theme][key] : undefined
}
