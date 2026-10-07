# Project Guidelines & Rules

## Mermaid Diagram Formatting & Responsive Layout
- **Check/Adapt to Viewport & Screen Width**: When creating Mermaid diagrams, always consider screen width and terminal display limitations to avoid horizontal scroll and clipping.
- **Prefer Vertical Orientation for Multi-Step Flows**: Use `flowchart TD` (Top-Down) or `graph TD` by default for long sequences or complex workflows instead of ultra-wide `flowchart LR`.
- **Wrap Labels and Prevent Overflow**: Break long text inside diagram nodes using `<br/>` or shorter descriptions to prevent nodes from expanding excessively wide.
- **Subgraphs and Logical Grouping**: Group related steps into vertical subgraphs or phased clusters so diagrams remain readable and compact on any screen width.
