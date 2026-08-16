# TributeJS 5.1.3 — vendored

The `@`-mention engine behind ProjectBlock's rich-text fields (mentions §1), attached to Jodit
by `<pg-editor>` rather than by a plugin package. Vendored rather than fetched, like every
other dependency here: nothing is loaded from a CDN at runtime.

## Why not `jodit-tributejs`

The requirement names it, and it was evaluated. It is **incompatible with the vendored Jodit**:

| | |
|---|---|
| `jodit-tributejs@1.0.1` | last published 2018, `dependencies: jodit ^3.1.76, tributejs ^3.2.0` |
| vendored Jodit | **4.13.37** |

Its twenty lines register through the Jodit **3** API — `Jodit.plugins.tributejs = fn` and
`editor.events.on(...)` — where Jodit 4 registers with `Jodit.plugins.add(name, plugin)`. It
also ships a hard-coded demo user list as its default options.

So the package would not attach, and its whole job is "make a Tribute and attach it to
`editor.editor`". `<pg-editor>` does exactly that, in the one place §21/§22 asks the mention
configuration to live — which is the same integration the requirement describes, minus a
stale dependency pinned to a different major version of the editor.

Source: https://github.com/zurb/tribute (MIT)
