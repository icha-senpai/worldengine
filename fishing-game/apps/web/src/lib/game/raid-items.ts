export function itemSlot(key: bigint) {
  return Number(key % 4_294_967_296n);
}
