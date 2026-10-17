import { describe, expect, it } from 'vitest';
import { dropIndex, endOfColumn, moveBody, placeAt } from './boardMove';

describe('board moves', () => {
  it('anchors before the task that will follow, or to the end of the column', () => {
    expect(moveBody('s', ['a', 'b', 'c'], 0)).toEqual({ status_id: 's', before_task_id: 'a' });
    expect(moveBody('s', ['a', 'b', 'c'], 2)).toEqual({ status_id: 's', before_task_id: 'c' });
    expect(moveBody('s', ['a', 'b', 'c'], 3)).toEqual({ status_id: 's', before_task_id: null });
    expect(moveBody('s', ['a', 'b', 'c'], 99)).toEqual({ status_id: 's', before_task_id: null });
    expect(moveBody('s', [], -1)).toEqual({ status_id: 's', before_task_id: null });
  });

  it('anchors after the last loaded card when the column has more pages', () => {
    expect(moveBody('s', ['a', 'b', 'c'], 3, true)).toEqual({ status_id: 's', after_task_id: 'c' });
    expect(moveBody('s', ['a', 'b', 'c'], 99, true)).toEqual({ status_id: 's', after_task_id: 'c' });
    expect(moveBody('s', ['a', 'b', 'c'], 1, true)).toEqual({ status_id: 's', before_task_id: 'b' });
    expect(moveBody('s', [], 0, true)).toEqual({ status_id: 's', before_task_id: null });
    expect(endOfColumn('s')).toEqual({ status_id: 's', before_task_id: null });
  });

  it('finds the drop index from the card midpoints', () => {
    expect(dropIndex([10, 30, 50], 5)).toBe(0);
    expect(dropIndex([10, 30, 50], 20)).toBe(1);
    expect(dropIndex([10, 30, 50], 60)).toBe(3);
    expect(dropIndex([], 60)).toBe(0);
  });

  it('places an item at an index for optimistic updates', () => {
    const [a, b, c] = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];

    expect(placeAt([a, b, c], a, 2).map((x) => x.id)).toEqual(['b', 'c', 'a']);
    expect(placeAt([a, b], c, 0).map((x) => x.id)).toEqual(['c', 'a', 'b']);
    expect(placeAt([a, b], c, 10).map((x) => x.id)).toEqual(['a', 'b', 'c']);
  });
});
