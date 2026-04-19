---
name: Model 3-level class hierarchy requirement
description: Glaurlink ORM uses getParentClass() reflection — model properties must be on an intermediate abstract class, not the concrete class
type: feedback
---

The ORM's save/insert/jsonSerialize use `new ReflectionClass($this)->getParentClass()` to discover public properties. This means test models (and user models) need a 3-level hierarchy:

1. `Model` (abstract base)
2. Schema class (abstract, declares public properties + static config)
3. Concrete class (empty, extends schema)

A 2-level hierarchy (concrete class directly extends Model) causes getParentClass() to return Model itself, which has no public instance properties → empty column lists.

**Why:** Discovered when tests first ran against a real DB. The README examples show a 2-level pattern that doesn't actually work — this is a latent ORM design issue.

**How to apply:** Always use the 3-level pattern in tests. If the user asks to fix this design issue, the change would be in how reflection discovers properties (e.g., using `new ReflectionClass($this)` instead of `->getParentClass()`).