# ADR 0005: Exclude a mutant only when no test could ever kill it

Status: Accepted (2026-10-19)

## Context

Infection reports a mutant as escaped when no test fails against it. Some
escapes are gaps in the suite. Others cannot be killed by any test, because the
mutant does not change behaviour (an equivalent mutant) or does not run at all
(a non-viable one, such as `break 3;` at a nesting depth of two, which PHP
rejects at compile time).

`infection.json5` excludes a short list of such mutants through
`ignoreSourceCodeByRegex`, each one justified by a measured survivor. The
mutation-testing guide said the opposite: that equivalent mutants must be
"replaced with behavior-changing mutants" and never excluded. A mutant cannot be
replaced. It is whatever the mutator makes of the code, so the guide's rule could
not be followed, and the config's practice had no written rule to hold it to.

The exclusions had also drifted. One hid every integer mutant on 35 declaration
lines because killing them was costly, not because they were equivalent. One
hid the `round(…, 3)` precision next to an equivalent divisor, because both sat
on one line. Comments counted three `break 2;` sites where there were six.

## Decision

The config's practice is right, written down here as a rule:

1. **Change the code first.** When a mutant is equivalent because the code
   around it is redundant, remove the redundancy so the mutant no longer exists.
   A no-op call is deleted, not excluded. When an equivalent mutant shares a
   line with a killable one, split the code so a regex can tell them apart.
2. **Exclude only non-viable or proven-equivalent mutants.** Each exclusion is
   scoped to one mutator, cites the survivor that was measured (run and file),
   and lists the sites its pattern matches.
3. **Pin every exclusion to its sites.** `tests/phpunit/InfectionExclusionsTest.php`
   holds the exact `file:line` set each pattern matches under `src/`. A new match
   or a moved line fails the test with the exclusion's name, so each new site is
   read and justified before it is hidden.
4. **A killable mutant is never excluded.** When killing it is costly (a
   megabyte fixture, a clock seam, a test that is awkward to write), it stays
   counted as an escape and lowers the score. "Not worth the cost" is not a
   reason to exclude.

## Consequences

- The measured MSI is an honest number: it counts every mutant a test could
  have caught, so it can drop when an exclusion is narrowed. That drop is the
  measurement correcting itself, and the floor in `infection.json5` is then set
  from a new full run, never kept by widening an exclusion.
- A new exclusion costs a measured survivor, a comment that argues why no test
  can kill it, and an entry in the pin. Excluding a mutator class wholesale
  (every `IncrementInteger` in `src`) is ruled out by point 2.
- A refactor that moves an excluded line fails the pin. Update the pin's
  `file:line` set in the same change, after you check the moved line still has
  the shape the exclusion's comment argues about.
