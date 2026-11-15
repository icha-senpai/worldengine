// BitCraft 1.0 ExperienceStack::experience_for_level, using Rust f32 rounding.
// Source: clockworklabs/BitCraftPublic@85c972d8d9433560fb51a906424b74b7958ddf99
// BitCraftServer/packages/game/src/game/entities/experience_stack.rs
// Current profession definitions cap at level 110. Keep thresholds local so
// progression never depends on availability of a world-specific player API.
const f32 = Math.fround;
const growth = f32(2 ** f32(0.158));
export const experienceLevels = Array.from({ length: 110 }, (_, index) => {
  const level = index + 1;
  const numerator = f32(f32(growth ** level) - growth);
  const denominator = f32(f32(growth * growth) - growth);
  const xp = Math.floor(f32(f32(52) * f32(numerator / denominator))) * 10;
  return { level, xp };
});
