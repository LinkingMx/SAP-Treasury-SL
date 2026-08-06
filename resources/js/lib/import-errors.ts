/**
 * Normalize an upload failure payload into a flat list of displayable errors.
 *
 * Our own import failures arrive as `errors: [{row, error}]`, but Laravel's
 * validation failures arrive as a keyed object (`errors: {file: ["..."]}`).
 * Checking only `Array.isArray(errors)` missed that shape and fell back to the
 * raw `message`, which is how users ended up reading "validation.mimes".
 */
export function normalizeImportErrors(
    data: unknown,
): { row: number; error: string }[] {
    const { errors, message } = (data ?? {}) as {
        errors?: unknown;
        message?: unknown;
    };

    if (Array.isArray(errors) && errors.length > 0) {
        return errors as { row: number; error: string }[];
    }

    if (errors && typeof errors === 'object') {
        const flat = Object.values(errors as Record<string, unknown>)
            .flatMap((value) => (Array.isArray(value) ? value : [value]))
            .filter(
                (value): value is string =>
                    typeof value === 'string' && value.trim() !== '',
            );

        if (flat.length > 0) {
            return flat.map((error) => ({ row: 0, error }));
        }
    }

    if (typeof message === 'string' && message.trim() !== '') {
        return [{ row: 0, error: message }];
    }

    return [
        {
            row: 0,
            error: 'No se pudo procesar el archivo. Verifica el formato e inténtalo de nuevo.',
        },
    ];
}
