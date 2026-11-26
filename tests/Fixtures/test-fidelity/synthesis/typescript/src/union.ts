// Momus: string-literal union aliases and inline literals.
export type Language = 'typescript' | 'php';
export type Severity = 'error' | 'warning' | 'info';
export class Union {
  lang(): Language { return "typescript"; }
  sev(): Severity { return "error"; }
  both(): { lang: Language; sev: Severity } { return { lang: "typescript", sev: "error" }; }
  inline(): 'on' | 'off' { return 'on'; }
}
