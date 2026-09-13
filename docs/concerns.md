# Pips

## New

- Because dominoes do not have backgrounds or borders that show on the board, you cannot tell where the dominoes are, and you cannot differentiate one domino from another. Also, when a domino is rotated such that one side is off the board, it does seem to show that. 

## General

- The dashes on the outlines on the dominoes are too close together. There should be more space between each dash.
- When rotating a domino in the tray before placing, it should only change the domino you are rotating. Currently, it resizes all of them.
- Each difficulty level currently uses approximately twice as many dominoes as intended. Easy should use 4-5 dominoes. Medium should use 6-8 dominoes. Hard should use 12-14 dominoes.
- Allow moving dominoes off the board. Dragging a domino from the board to anywhere outside the board returns it to the tray.
- Remove the blue outline with the checkmark. That should not be there. There should also not be Xs in the wrong spots. Do not show any verification until every domino is placed.
- Once every domino is placed, if the board is correct, outline the entire board in green and show a banner at the top saying that the board is correct. A correct board cannot be interacted with after verification.
- Once every domino is placed, if anything on the board is incorrect, outline the board in red and show a banner saying that the board is incorrect. Do not outline specific dominoes or indicate what is wrong. When the user interacts with an incorrect board, automatically remove the red outline and banner. Keep them hidden until every spot is filled again.

## Rotating Dominoes

- Tap or click to rotate dominoes by 90 degrees. The rotation should be animated slowly because without animation, a 90-degree rotation would be jarring.
- Dominoes can be rotated 90 degrees in the tray (bottom section) and on the board (top section)
- A domino becomes slightly lighter and semitransparent to indicate it is being rotated. This styling applies in the tray and on the board.
- Clicking a domino in the tray rotates it clockwise around the center of the domino, so its center does not move. It does not affect any other domino in the tray. It may overlap the other dominoes. That is fine. The other dominoes do not move out of the way or reflow.
- Dominoes in the tray are horizontal when not rotated. If a domino in the tray is rotated into a horizontal position, it is styled like a standard tray domino rather than a rotated domino.
- Clicking a domino on the board rotates it 90 degrees around the side you click on. If you click close to the center, it rotates around the right side. When a domino is rotated while on the board, it uses its default tray colors, not the colors of the space it occupies. However, it appears lighter and semitransparent, allowing the underlying color to show through slightly. The domino rotates around one side, not the center, so it ends in a position that could be valid. A valid position is one where both halves are on the board in unoccupied squares. The squares from which the domino is rotated are considered unoccupied because the rotation removes the domino from the board. If the domino ends up in a valid location, it transitions to its place style. If the position is not valid—because half of it is either off the board or in an occupied square—it remains in its rotated styling.
