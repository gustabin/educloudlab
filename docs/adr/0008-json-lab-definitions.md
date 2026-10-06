# ADR-008: JSON lab definitions with a closed set of declarative checks

- Status: Accepted

## Decision
- Labs are `labs/LAB-xxx/lab.json` files validated by `opis/json-schema`. No YAML extension is installed.
- Checks come from a closed, trusted registry. Lab definitions contain no executable validation code.
- Validation always inspects the actual server-side state.
