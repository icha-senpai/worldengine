import type { Game } from './game.svelte';
import type { ControlOption } from './custom-control-logic';
import { gearLabel } from './tideforge-ui';

type Kind = 'rod' | 'boat' | 'net';

export function tackleGearOptions(game: Game, kind: Kind): ControlOption<string>[] {
  if (!game.player || game.equipmentInformationUnavailable) return [];
  const level = game.profile?.level ?? 1;
  if (game.gearLoadout) {
    if (game.gearLoadout.playerId !== game.player.playerId) return [];
    return game.gearInstances.filter(item => item.playerId === game.player?.playerId && item.kind === kind)
      .sort((a, b) => a.instanceId < b.instanceId ? -1 : a.instanceId > b.instanceId ? 1 : 0)
      .map(item => {
        const family = game.rods.find(row => row.rodId === item.familyId);
        const definition = kind === 'rod'
          ? game.rods.find(row => row.rodId === item.definitionId)
          : game.haulEquipment.find(row => row.equipmentId === item.definitionId && row.kind === kind);
        const required = family?.minimumLevel;
        const quality = game.qualities.find(row => row.qualityLevel === item.qualityLevel)?.name ?? 'Common';
        const suffix = required !== undefined && required > level ? ` · Level ${required} required` : '';
        return { value: `instance:${item.instanceId}`, label: `${quality} ${gearLabel(game, item)}${suffix}`,
          disabled: !family || !definition || required === undefined || required > level };
      });
  }
  if (kind === 'rod') {
    return game.rods.filter(row => game.ownedRods.some(item => item.rodId === row.rodId)).map(rod => {
      const owned = game.ownedRods.find(item => item.rodId === rod.rodId);
      const quality = game.qualities.find(row => row.qualityLevel === owned?.upgradeLevel)?.name ?? 'Common';
      const suffix = rod.minimumLevel > level ? ` · Level ${rod.minimumLevel} required` : '';
      return { value: `legacy:${rod.rodId}`, label: `${quality} ${rod.name}${suffix}`, disabled: rod.minimumLevel > level };
    });
  }
  return game.haulEquipment.filter(row => row.kind === kind && game.ownedHaulEquipment.some(item => item.equipmentId === row.equipmentId))
    .map(equipment => {
      const family = game.rods.find(row => row.rodId === equipment.rodId);
      const owned = game.ownedHaulEquipment.find(row => row.equipmentId === equipment.equipmentId);
      const quality = game.qualities.find(row => row.qualityLevel === owned?.upgradeLevel)?.name ?? 'Common';
      const required = family?.minimumLevel;
      const suffix = required !== undefined && required > level ? ` · Level ${required} required` : '';
      return { value: `legacy:${equipment.equipmentId}`, label: `${quality} ${equipment.name}${suffix}`,
        disabled: !family || required === undefined || required > level };
    });
}

export function tackleGearValue(game: Game, kind: Kind): string {
  if (!game.player || game.equipmentInformationUnavailable) return '';
  if (game.gearLoadout) {
    if (game.gearLoadout.playerId !== game.player.playerId) return '';
    const id = kind === 'rod' ? game.gearLoadout.rodInstanceId
      : kind === 'boat' ? game.gearLoadout.boatInstanceId : game.gearLoadout.netInstanceId;
    return game.gearInstances.some(row => row.playerId === game.player?.playerId && row.kind === kind && row.instanceId === id)
      ? `instance:${id}` : '';
  }
  const id = kind === 'rod' ? game.player.equippedRodId
    : kind === 'boat' ? game.haulLoadout?.boatId : game.haulLoadout?.netId;
  return id === undefined ? '' : `legacy:${id}`;
}

export async function equipTackleGear(game: Game, kind: Kind, value: string): Promise<boolean> {
  if (!game.ready || game.busy || !game.player || game.equipmentInformationUnavailable) return false;
  const option = tackleGearOptions(game, kind).find(row => row.value === value && !row.disabled);
  if (!option) return false;
  if (value === tackleGearValue(game, kind)) return true;
  if (value.startsWith('instance:')) return game.equipGearInstance(BigInt(value.slice('instance:'.length)));
  const id = Number(value.slice('legacy:'.length));
  if (kind === 'rod') await game.changeLoadout(undefined, id);
  else await game.equipHaulEquipment(id);
  return !game.error;
}
