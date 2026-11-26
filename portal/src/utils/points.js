export const POINT_SCENE_LABELS = {
  register: '注册奖励',
  signin: '签到奖励',
  comment: '评论奖励',
  admin_adjust: '管理员调整',
  test: '测试',
};

export function pointSceneLabel(scene) {
  return POINT_SCENE_LABELS[scene] || '积分变动';
}
