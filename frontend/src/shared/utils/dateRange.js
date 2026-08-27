/** Both dates are 'YYYY-MM-DD' strings; inclusive of both start and end date. */
export function daysBetweenInclusive(start, end) {
    const s = new Date(`${start}T00:00:00`);
    const e = new Date(`${end}T00:00:00`);
    const diff = Math.round((e - s) / (1000 * 60 * 60 * 24));
    return diff + 1;
}

/** Today as 'YYYY-MM-DD', used as the min for the date pickers. */
export function todayIso() {
    return new Date().toISOString().slice(0, 10);
}